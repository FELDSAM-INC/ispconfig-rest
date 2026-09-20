<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_database_operations', fn (Blueprint $table) => $table->unsignedInteger('chunk_size')->nullable());
    }

    public function down(): void
    {
        Schema::table('api_database_operations', fn (Blueprint $table) => $table->dropColumn('chunk_size'));
    }
};
