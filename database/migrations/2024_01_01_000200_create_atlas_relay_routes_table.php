<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('atlas-relay.tables.relay_routes', 'atlas_relay_routes');

        Schema::create($tableName, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('identifier')->nullable();
            $table->string('method', 16);
            $table->string('path');
            $table->string('type', 32);
            $table->string('destination_url');
            $table->json('headers')->nullable();
            $table->json('retry_policy')->nullable();
            $table->boolean('is_retry')->default(false);
            $table->unsignedInteger('retry_seconds')->nullable();
            $table->unsignedInteger('retry_max_attempts')->nullable();
            $table->boolean('is_delay')->default(false);
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->unsignedInteger('http_timeout_seconds')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['method', 'path']);
            $table->index('identifier');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        $tableName = config('atlas-relay.tables.relay_routes', 'atlas_relay_routes');

        Schema::dropIfExists($tableName);
    }
};
