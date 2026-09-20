<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_database_operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('upload_bytes')->nullable();
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_database_operations', fn (Blueprint $table) => $table->dropColumn(['upload_bytes', 'uploaded_bytes', 'download_bytes']));
    }
};
