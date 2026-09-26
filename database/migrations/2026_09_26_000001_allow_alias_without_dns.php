<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_alias_services', function (Blueprint $table): void {
            $table->unsignedInteger('dns_zone_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Aliases without DNS cannot be converted to a mandatory zone without inventing DNS data.
    }
};
