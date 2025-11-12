<?php

declare(strict_types=1);

namespace Atlas\Relay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model that reads its table name from the atlas-relay config map used by every PRD-driven data structure.
 */
abstract class AtlasModel extends Model
{
    protected $guarded = [];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->setTable(config($this->tableNameConfigKey(), $this->defaultTableName()));
        $connection = config('atlas-relay.database.connection');

        if ($connection) {
            $this->setConnection($connection);
        }

        parent::__construct($attributes);
    }

    abstract protected function tableNameConfigKey(): string;

    abstract protected function defaultTableName(): string;
}
