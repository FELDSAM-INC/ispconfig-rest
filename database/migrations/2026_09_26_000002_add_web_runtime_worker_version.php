<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_web_log_workers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('runtime_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('api_web_log_workers', function (Blueprint $table): void {
            $table->dropColumn('runtime_version');
        });
    }
};
