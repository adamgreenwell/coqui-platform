<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token with a public UUID, so the token management screens never put
 * the sequential token ID in a URL. Sanctum itself still authenticates with
 * the `id|secret` plain-text format.
 *
 * @property string $uuid
 * @property int|null $workspace_id The only workspace the token works in (null: legacy, unscoped)
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasPublicUuid;
}
