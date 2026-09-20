<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_alias_services', function (Blueprint $table): void {
            $table->unsignedInteger('web_domain_id')->primary();
            $table->unsignedInteger('sys_groupid')->index();
            $table->unsignedInteger('dns_zone_id')->unique();
            $table->unsignedInteger('primary_zone_id')->nullable()->index();
            $table->string('primary_domain');
            $table->boolean('dns_sync')->default(false);
            $table->unsignedInteger('mail_alias_id')->nullable();
            $table->unsignedInteger('mail_domain_id')->nullable();
            $table->boolean('created_mail_domain')->default(false);
            $table->text('record_map')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_alias_services');
    }
};
