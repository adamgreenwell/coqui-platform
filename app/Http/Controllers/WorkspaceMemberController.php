<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceMemberController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        $workspace->load(['members', 'owner']);

        $members = $workspace->members->map(function (User $member) use ($workspace): array {
            $pivotRole = $member->pivot?->getAttribute('role');
            $pivotCreatedAt = $member->pivot?->getAttribute('created_at');
            $joinedAt = $pivotCreatedAt instanceof CarbonInterface
                ? $pivotCreatedAt->toIso8601String()
                : (is_string($pivotCreatedAt) ? $pivotCreatedAt : null);

            return [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => is_string($pivotRole) ? $pivotRole : ($member->id === $workspace->owner_id ? 'owner' : 'viewer'),
                'joinedAt' => $joinedAt,
                'isOwner' => $member->id === $workspace->owner_id,
            ];
        })->values()->all();

        return Inertia::render('Workspaces/Members', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'members' => $members,
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:admin,editor,viewer'],
        ]);

        $userToInvite = User::query()->where('email', (string) $validated['email'])->first();

        if ($userToInvite === null) {
            return back()->withErrors(['email' => 'No user found with this email address.']);
        }

        if ($workspace->members()->where('users.id', $userToInvite->id)->exists()) {
            return back()->withErrors(['email' => 'User is already a member of this workspace.']);
        }

        $workspace->members()->attach($userToInvite->id, [
            'role' => (string) $validated['role'],
        ]);

        return back()->with('success', "Added {$userToInvite->name} to {$workspace->name}.");
    }

    public function update(Request $request, Workspace $workspace, User $member): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);

        if ($member->id === $workspace->owner_id) {
            return back()->withErrors(['role' => 'Cannot change the workspace owner role.']);
        }

        $validated = $request->validate([
            'role' => ['required', 'in:admin,editor,viewer'],
        ]);

        $workspace->members()->updateExistingPivot($member->id, [
            'role' => (string) $validated['role'],
        ]);

        return back()->with('success', "Updated {$member->name}'s role.");
    }

    public function destroy(Request $request, Workspace $workspace, User $member): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);

        if ($member->id === $workspace->owner_id) {
            return back()->withErrors(['member' => 'Cannot remove the workspace owner.']);
        }

        $workspace->members()->detach($member->id);

        return back()->with('success', "Removed {$member->name} from {$workspace->name}.");
    }
}
