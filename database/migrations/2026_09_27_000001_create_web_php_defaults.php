<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_web_php_defaults', function (Blueprint $table): void {
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('server_php_id');
            $table->string('mode', 16);
            $table->text('settings');
            $table->unsignedBigInteger('measured_at');
            $table->primary(['server_id', 'server_php_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_web_php_defaults');
    }
};
