<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-owned table (constitution: never migrate ISPConfig tables; API-owned
 * tables must be clearly separate — this is the only one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key_hash', 64)->unique();
            $table->unsignedInteger('sys_userid')->default(1);
            $table->unsignedInteger('sys_groupid')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
