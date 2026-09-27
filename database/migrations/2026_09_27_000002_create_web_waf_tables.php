<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_web_waf_workers', function (Blueprint $table): void {
            $table->unsignedInteger('server_id')->primary();
            $table->unsignedBigInteger('heartbeat');
            $table->string('engine', 16);
            $table->string('rules_version', 128)->nullable();
            $table->boolean('atomic_available')->default(false);
        });
        Schema::create('api_web_waf_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_key', 64)->unique();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('website_id');
            $table->string('identity', 48);
            $table->unsignedBigInteger('occurred_at');
            $table->unsignedInteger('rule_id');
            $table->string('outcome', 16);
            $table->string('client_ip', 45);
            $table->string('method', 16);
            $table->string('path', 512);
            $table->string('parameter', 128)->default('');
            $table->string('message', 512);
            $table->string('severity', 32)->default('');
            $table->string('source', 16);
            $table->index(['server_id', 'website_id', 'identity', 'id'], 'api_waf_events_site');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_web_waf_events');
        Schema::dropIfExists('api_web_waf_workers');
    }
};
