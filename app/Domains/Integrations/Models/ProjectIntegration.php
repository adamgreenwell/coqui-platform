<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Projects\Models\Project;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $provider
 * @property string|null $site_url
 * @property string|null $external_identifier
 * @property array<string, mixed>|null $credentials_encrypted
 * @property string $status
 * @property CarbonInterface|null $last_synced_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Project|null $project
 */
class ProjectIntegration extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'provider',
        'site_url',
        'external_identifier',
        'credentials_encrypted',
        'status',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials_encrypted' => 'encrypted:array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeConnected(Builder $query): void
    {
        $query->where('status', 'connected');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForProvider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }

    public function isHealthy(): bool
    {
        return $this->status === 'connected';
    }

    public function needsReconnect(): bool
    {
        return in_array($this->status, ['needs_reconnect', 'revoked'], true);
    }

    public function markNeedsReconnect(string $reason = 'Token expired or revoked'): void
    {
        $this->update(['status' => 'needs_reconnect']);
    }

    public function markConnected(): void
    {
        $this->update(['status' => 'connected']);
    }

    public function getAccessToken(): ?string
    {
        /** @var array<string, mixed>|null $creds */
        $creds = $this->credentials_encrypted;

        return isset($creds['access_token']) && is_string($creds['access_token']) ? $creds['access_token'] : null;
    }

    public function getRefreshToken(): ?string
    {
        /** @var array<string, mixed>|null $creds */
        $creds = $this->credentials_encrypted;

        return isset($creds['refresh_token']) && is_string($creds['refresh_token']) ? $creds['refresh_token'] : null;
    }

    public function isTokenExpired(): bool
    {
        /** @var array<string, mixed>|null $creds */
        $creds = $this->credentials_encrypted;
        if (! isset($creds['expires_at'])) {
            return false;
        }

        $expiresAt = Carbon::parse($creds['expires_at']);

        // Treat token as expired 60 seconds before actual expiration
        return Carbon::now()->addSeconds(60)->gte($expiresAt);
    }

    /**
     * Update stored credentials with new token attributes.
     */
    public function updateTokens(string $accessToken, ?string $refreshToken = null, ?int $expiresInSeconds = null): void
    {
        /** @var array<string, mixed> $creds */
        $creds = is_array($this->credentials_encrypted) ? $this->credentials_encrypted : [];
        $creds['access_token'] = $accessToken;

        if ($refreshToken !== null) {
            $creds['refresh_token'] = $refreshToken;
        }

        if ($expiresInSeconds !== null) {
            $creds['expires_at'] = Carbon::now()->addSeconds($expiresInSeconds)->toIso8601String();
        }

        $this->update([
            'credentials_encrypted' => $creds,
            'status' => 'connected',
        ]);
    }
}
