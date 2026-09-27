<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_wordpress_workers', function (Blueprint $table): void {
            $table->unsignedInteger('server_id')->primary();
            $table->unsignedBigInteger('heartbeat');
            $table->string('version', 32);
            $table->boolean('available')->default(false);
            $table->string('reason', 64)->nullable();
        });
        Schema::create('api_wordpress_sites', function (Blueprint $table): void {
            $table->unsignedInteger('website_id')->primary();
            $table->unsignedInteger('server_id');
            $table->string('identity', 64);
            $table->unsignedBigInteger('scanned_at');
            $table->boolean('incomplete')->default(false);
            $table->mediumText('installations');
        });
        Schema::create('api_wordpress_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('website_id');
            $table->unsignedInteger('server_id');
            $table->string('identity', 64);
            $table->string('action', 16);
            $table->string('status', 24)->default('queued');
            $table->text('request');
            $table->uuid('backup_id')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('started_at')->nullable();
            $table->unsignedBigInteger('finished_at')->nullable();
            $table->string('error', 64)->nullable();
            $table->index(['server_id', 'status', 'created_at'], 'api_wp_queue');
            $table->index(['website_id', 'identity', 'created_at'], 'api_wp_site_jobs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_wordpress_jobs');
        Schema::dropIfExists('api_wordpress_sites');
        Schema::dropIfExists('api_wordpress_workers');
    }
};
