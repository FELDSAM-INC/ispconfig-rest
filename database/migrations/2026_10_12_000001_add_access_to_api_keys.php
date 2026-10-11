<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key access (spec 057): `full` keys use the whole API their scope allows, `acme` keys only ACME DNS-01 challenges,
 * optionally limited to the zones in `zones` (JSON array of origins). API-owned table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('access', 16)->default('full')->after('sys_groupid');
            $table->text('zones')->nullable()->after('access');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['access', 'zones']);
        });
    }
};
