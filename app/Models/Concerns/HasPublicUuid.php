<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Gives a model a public UUIDv7 in a `uuid` column and uses it as the route key.
 *
 * The integer primary key stays the real key for joins and foreign keys; only
 * URLs and client-facing payloads use the UUID, so record counts can't be
 * guessed by walking sequential IDs. Binding a non-UUID value (such as an old
 * integer ID) to a route throws ModelNotFoundException, which renders a 404.
 */
trait HasPublicUuid
{
    use HasUuids;

    /**
     * Only the `uuid` column is generated; the primary key keeps auto-incrementing.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
