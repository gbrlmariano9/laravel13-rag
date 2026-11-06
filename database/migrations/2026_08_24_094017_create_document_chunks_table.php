<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('chunk_index');

            $table->text('content');

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE document_chunks ADD COLUMN embedding vector(768)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
