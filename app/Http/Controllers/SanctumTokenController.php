<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class SanctumTokenController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        /** @var User $user */
        $user = $request->user();

        /** @var list<array{id: int, name: string, abilities: list<string>|null, lastUsedAt: string|null, createdAt: string|null}> $tokens */
        $tokens = $user->tokens()
            ->latest('id')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at'])
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => (int) $token->id,
                'name' => (string) $token->name,
                'abilities' => $token->abilities,
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'createdAt' => $token->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Workspaces/Tokens', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'tokens' => $tokens,
            'plainTextToken' => session('plainTextToken'),
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('createTokens', $workspace);

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:crawls:sync,projects:read'],
        ]);

        /** @var list<string> $abilities */
        $abilities = array_values((array) $validated['abilities']);

        $name = (string) $validated['name'];
        $token = $user->createToken($name, $abilities);

        return back()
            ->with('plainTextToken', $token->plainTextToken)
            ->with('success', "Desktop token [{$name}] generated. Copy it now; it won't be shown again.");
    }

    public function destroy(Request $request, Workspace $workspace, int|string $token): RedirectResponse
    {
        Gate::authorize('createTokens', $workspace);

        /** @var User $user */
        $user = $request->user();

        $deleted = $user->tokens()->whereKey($token)->delete();

        if ($deleted === 0) {
            abort(404, 'Token not found.');
        }

        return back()->with('success', 'Token revoked successfully.');
    }
}
