<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_database_workers', function (Blueprint $table): void {
            $table->unsignedInteger('server_id')->primary();
            $table->unsignedInteger('heartbeat');
        });
        Schema::create('api_database_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('database_id')->index();
            $table->unsignedInteger('target_database_id')->nullable()->index();
            $table->unsignedInteger('sys_groupid');
            $table->unsignedInteger('server_id')->index();
            $table->string('database_name', 64);
            $table->string('action', 8);
            $table->string('status', 12)->default('queued')->index();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->unsignedInteger('expires_at')->index();
            $table->string('error', 80)->nullable();
        });
        Schema::create('api_database_operation_chunks', function (Blueprint $table): void {
            $table->uuid('operation_id');
            $table->unsignedInteger('sequence');
            $table->mediumText('content'); // base64, portable SQLite/MySQL; no raw binary or packet-sized dumps
            $table->primary(['operation_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_database_operation_chunks');
        Schema::dropIfExists('api_database_operations');
        Schema::dropIfExists('api_database_workers');
    }
};
