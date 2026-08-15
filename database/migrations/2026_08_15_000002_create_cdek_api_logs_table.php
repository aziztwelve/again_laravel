<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cdek_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cdek_order_id')->nullable()->constrained()->nullOnDelete();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('cdek_api_logs');
    }
};
