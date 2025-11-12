<?php

declare(strict_types=1);

use AtlasRelay\Enums\RelayStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('atlas-relay.tables.relays', 'atlas_relays');

        Schema::create($tableName, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('request_source')->nullable();
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('status')->default(RelayStatus::QUEUED->value);
            $table->string('mode', 32)->nullable();
            $table->unsignedBigInteger('route_id')->nullable();
            $table->string('route_identifier')->nullable();
            $table->string('destination_type', 32)->nullable();
            $table->string('destination_url')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_payload')->nullable();
            $table->boolean('response_payload_truncated')->default(false);
            $table->unsignedSmallInteger('failure_reason')->nullable();
            $table->boolean('is_retry')->default(false);
            $table->unsignedInteger('retry_seconds')->nullable();
            $table->unsignedInteger('retry_max_attempts')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->boolean('is_delay')->default(false);
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->unsignedInteger('http_timeout_seconds')->nullable();
            $table->unsignedInteger('last_attempt_duration_ms')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('first_attempted_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_finished_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('retry_at');
            $table->index('route_id');
            $table->index('route_identifier');
            $table->index('failed_at');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        $tableName = config('atlas-relay.tables.relays', 'atlas_relays');

        Schema::dropIfExists($tableName);
    }
};
