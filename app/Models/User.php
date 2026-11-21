<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Projects\Models\Workspace;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_operator
 * @property Pivot|null $pivot
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicUuid, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_operator' => 'boolean',
        ];
    }

    /**
     * Platform operator (staff). Not mass-assignable; set via `coqui:operator`.
     */
    public function isOperator(): bool
    {
        return (bool) $this->is_operator;
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The workspace this request's API token is limited to, or null for a
     * browser session or a token created before tokens were scoped.
     */
    public function tokenWorkspaceId(): ?int
    {
        $token = $this->currentAccessToken();
        // Only a stored token can carry a scope (Sanctum::actingAs() in tests
        // uses an unsaved stand-in).
        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            return null;
        }

        $workspaceId = $token->getAttribute('workspace_id');

        return is_numeric($workspaceId) ? (int) $workspaceId : null;
    }

    public function tokenAllowsWorkspace(int $workspaceId): bool
    {
        $scoped = $this->tokenWorkspaceId();

        return $scoped === null || $scoped === $workspaceId;
    }
}
