<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_web_log_workers', function (Blueprint $table): void {
            $table->unsignedInteger('server_id')->primary();
            $table->unsignedInteger('heartbeat');
        });
        Schema::create('api_web_log_reads', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->unsignedInteger('server_id')->index();
            $table->unsignedInteger('website_id');
            $table->unsignedInteger('sys_groupid');
            $table->string('domain', 255);
            $table->text('request');
            $table->mediumText('result')->nullable();
            $table->unsignedInteger('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_web_log_reads');
        Schema::dropIfExists('api_web_log_workers');
    }
};
