<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Administrator-set account/website cgroup limits (spec 053).
        Schema::create('api_client_resource_limits', function (Blueprint $table): void {
            $table->unsignedInteger('client_id')->primary();
            $table->text('settings');
            $table->unsignedBigInteger('revision');
        });
        // One row per webserver running the php-limits worker.
        Schema::create('api_php_limits_workers', function (Blueprint $table): void {
            $table->unsignedInteger('server_id')->primary();
            $table->unsignedBigInteger('heartbeat');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 32);
            $table->text('services')->nullable();
        });
        // Measured usage per account slice (scope account, id client_id) and
        // per isolated pool (scope website, id domain_id) on each server.
        Schema::create('api_php_limits_usage', function (Blueprint $table): void {
            $table->unsignedInteger('server_id');
            $table->string('scope', 8);
            $table->unsignedInteger('scope_id');
            $table->unsignedInteger('client_id')->index();
            $table->string('state', 16);
            $table->string('reason', 64)->nullable();
            $table->string('php_service', 32)->nullable();
            $table->unsignedBigInteger('applied_revision')->nullable();
            $table->unsignedBigInteger('memory_bytes')->nullable();
            $table->unsignedBigInteger('memory_peak_bytes')->nullable();
            $table->unsignedBigInteger('memory_limit_bytes')->nullable();
            $table->unsignedInteger('memory_limit_hits_24h')->default(0);
            $table->float('cpu_percent')->nullable();
            $table->float('cpu_percent_24h')->nullable();
            $table->unsignedInteger('cpu_limit_percent')->nullable();
            $table->unsignedSmallInteger('cpu_limited_minutes_24h')->default(0);
            $table->unsignedInteger('tasks')->nullable();
            $table->unsignedInteger('tasks_limit')->nullable();
            $table->unsignedInteger('tasks_limit_hits_24h')->default(0);
            $table->unsignedBigInteger('measured_at');
            $table->primary(['server_id', 'scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_php_limits_usage');
        Schema::dropIfExists('api_php_limits_workers');
        Schema::dropIfExists('api_client_resource_limits');
    }
};
