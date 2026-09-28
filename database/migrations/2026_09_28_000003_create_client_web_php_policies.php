<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_client_web_php_policies', function (Blueprint $table): void {
            $table->unsignedInteger('client_id')->primary();
            $table->text('settings');
            $table->text('original_php_modes')->nullable();
        });
        Schema::create('api_web_php_policy_sites', function (Blueprint $table): void {
            $table->unsignedInteger('domain_id')->primary();
            $table->unsignedInteger('client_id')->index();
            $table->text('original_fields');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_web_php_policy_sites');
        Schema::dropIfExists('api_client_web_php_policies');
    }
};
