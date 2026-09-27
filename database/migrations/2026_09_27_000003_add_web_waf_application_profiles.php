<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_web_waf_workers', function (Blueprint $table): void {
            $table->text('application_profiles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_web_waf_workers', function (Blueprint $table): void {
            $table->dropColumn('application_profiles');
        });
    }
};
