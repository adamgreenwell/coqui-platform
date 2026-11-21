<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Workspace;
use App\Domains\Projects\Models\WorkspaceActivity;
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
                'id' => $member->uuid,
                'name' => $member->name,
                'email' => $member->email,
                'role' => is_string($pivotRole) ? $pivotRole : ($member->id === $workspace->owner_id ? 'owner' : 'viewer'),
                'joinedAt' => $joinedAt,
                'isOwner' => $member->id === $workspace->owner_id,
            ];
        })->values()->all();

        /** @var list<array{id: int, eventType: string, title: string, description: string|null, userName: string|null, createdAt: string}> $history */
        $history = $workspace->activities()
            ->with('user')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (WorkspaceActivity $a): array => [
                'id' => $a->id,
                'eventType' => $a->event_type,
                'title' => $a->title,
                'description' => $a->description,
                'userName' => $a->user?->name,
                'createdAt' => $a->created_at->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Workspaces/Members', [
            'workspace' => [
                'id' => $workspace->uuid,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'members' => $members,
            'history' => $history,
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

        /** @var User $actor */
        $actor = $request->user();
        WorkspaceActivity::log(
            workspace: $workspace,
            eventType: 'workspace.member_added',
            title: 'Member added',
            description: sprintf('%s added %s as %s.', $actor->name, $userToInvite->name, (string) $validated['role']),
            properties: ['member_id' => $userToInvite->uuid, 'member_name' => $userToInvite->name, 'role' => (string) $validated['role']],
            user: $actor,
        );

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

        $membership = $workspace->members()->where('users.id', $member->id)->first();
        if ($membership === null) {
            return back()->withErrors(['member' => "{$member->name} isn't a member of this workspace."]);
        }

        $previousRole = $membership->pivot->getAttribute('role');
        if ($previousRole === (string) $validated['role']) {
            // Nothing changed, so nothing to record.
            return back()->with('success', "{$member->name} is already a {$previousRole}.");
        }

        $workspace->members()->updateExistingPivot($member->id, [
            'role' => (string) $validated['role'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        WorkspaceActivity::log(
            workspace: $workspace,
            eventType: 'workspace.member_role_changed',
            title: 'Member role changed',
            description: sprintf(
                '%s changed %s from %s to %s.',
                $actor->name,
                $member->name,
                is_string($previousRole) ? $previousRole : 'unknown',
                (string) $validated['role'],
            ),
            properties: [
                'member_id' => $member->uuid,
                'member_name' => $member->name,
                'previous_role' => is_string($previousRole) ? $previousRole : null,
                'role' => (string) $validated['role'],
            ],
            user: $actor,
        );

        return back()->with('success', "Updated {$member->name}'s role.");
    }

    public function destroy(Request $request, Workspace $workspace, User $member): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);

        if ($member->id === $workspace->owner_id) {
            return back()->withErrors(['member' => 'Cannot remove the workspace owner.']);
        }

        $previousRole = $workspace->members()->where('users.id', $member->id)->first()?->pivot?->getAttribute('role');

        // Only record a removal that actually happened (not a repeat, and
        // not someone from another workspace).
        if ($workspace->members()->detach($member->id) === 0) {
            return back()->withErrors(['member' => "{$member->name} isn't a member of this workspace."]);
        }

        /** @var User $actor */
        $actor = $request->user();
        WorkspaceActivity::log(
            workspace: $workspace,
            eventType: 'workspace.member_removed',
            title: 'Member removed',
            description: sprintf('%s removed %s from the workspace.', $actor->name, $member->name),
            properties: [
                'member_id' => $member->uuid,
                'member_name' => $member->name,
                'previous_role' => is_string($previousRole) ? $previousRole : null,
            ],
            user: $actor,
        );

        return back()->with('success', "Removed {$member->name} from {$workspace->name}.");
    }
}
