<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_wordpress_cron', function (Blueprint $table): void {
            $table->text('last_output')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_wordpress_cron', function (Blueprint $table): void {
            $table->dropColumn('last_output');
        });
    }
};
