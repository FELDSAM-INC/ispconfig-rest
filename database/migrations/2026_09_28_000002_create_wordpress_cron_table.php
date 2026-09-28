<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_wordpress_cron', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('website_id');
            $table->unsignedInteger('server_id');
            $table->string('installation', 32);
            $table->string('identity', 64);
            $table->string('path', 1024);
            $table->unsignedInteger('cron_id')->nullable()->unique();
            $table->unsignedInteger('interval')->default(15);
            $table->string('state', 16)->default('enabling');
            $table->boolean('previous_captured')->default(false);
            $table->string('previous_value', 8)->nullable();
            $table->unsignedBigInteger('last_run')->nullable();
            $table->string('error', 64)->nullable();
            $table->unique(['website_id', 'installation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_wordpress_cron');
    }
};
