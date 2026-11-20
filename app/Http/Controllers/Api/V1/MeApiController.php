<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domains\Projects\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspaces = Workspace::query()
            ->where('owner_id', $user->id)
            ->orWhereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'slug', 'plan_tier', 'owner_id'])
            ->map(function (Workspace $ws) use ($user): array {
                $role = 'viewer';
                if ($ws->owner_id === $user->id) {
                    $role = 'owner';
                } else {
                    $membership = $ws->members()->where('users.id', $user->id)->first();
                    $membershipRole = $membership?->pivot?->getAttribute('role');
                    if (is_string($membershipRole)) {
                        $role = $membershipRole;
                    }
                }

                return [
                    'id' => $ws->uuid,
                    'name' => $ws->name,
                    'slug' => $ws->slug,
                    'planTier' => $ws->plan_tier,
                    'role' => $role,
                ];
            })
            ->all();

        return response()->json([
            'user' => [
                'id' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'workspaces' => $workspaces,
        ]);
    }
}
