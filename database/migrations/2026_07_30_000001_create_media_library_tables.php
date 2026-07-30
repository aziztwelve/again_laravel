<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('media_fileables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_file_id')->constrained()->cascadeOnDelete();
            $table->morphs('media_fileable');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_main')->default(false);
            $table->unique(['media_file_id', 'media_fileable_type', 'media_fileable_id'], 'media_fileable_unique');
            $table->index(['media_fileable_type', 'media_fileable_id', 'position'], 'media_target_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_fileables');
        Schema::dropIfExists('media_files');
    }
};
