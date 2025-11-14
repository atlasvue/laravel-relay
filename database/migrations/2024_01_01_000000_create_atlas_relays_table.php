<?php

declare(strict_types=1);

use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Enums\RelayType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('atlas-relay.tables.relays', 'atlas_relays');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedTinyInteger('type')->default(RelayType::RELAY->value);
            $table->unsignedTinyInteger('status')->default(RelayStatus::QUEUED->value);
            $table->string('provider', 64)->nullable();
            $table->string('reference_id', 255)->nullable();
            $table->string('source_ip', 15)->nullable();
            $table->json('headers')->nullable();
            $table->string('method', 16)->nullable();
            $table->string('url')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('response_http_status')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('failure_reason')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('provider');
            $table->index('reference_id');
        });
    }

    public function down(): void
    {
        $tableName = config('atlas-relay.tables.relays', 'atlas_relays');

        $this->schema()->dropIfExists($tableName);
    }

    private function schema(): Builder
    {
        $connection = config('atlas-relay.database.connection');
        $connectionName = $connection ?: config('database.default');

        return Schema::connection($connectionName);
    }
};
