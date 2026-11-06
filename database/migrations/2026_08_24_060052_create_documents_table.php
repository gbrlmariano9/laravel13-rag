<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE documents ADD COLUMN embedding vector(768)');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
