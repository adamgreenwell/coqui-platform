<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sessionWorkspaceId = session('current_workspace_id');
        if ($sessionWorkspaceId !== null) {
            $workspace = Workspace::query()->find($sessionWorkspaceId);
            if ($workspace !== null && $user->can('view', $workspace)) {
                return redirect()->route('workspaces.show', $workspace);
            }
        }

        /** @var Workspace|null $firstWorkspace */
        $firstWorkspace = Workspace::query()
            ->where('owner_id', $user->id)
            ->orWhereHas('members', fn ($q) => $q->where('users.id', $user->id))
            ->first();

        if ($firstWorkspace !== null) {
            session(['current_workspace_id' => $firstWorkspace->id]);

            return redirect()->route('workspaces.show', $firstWorkspace);
        }

        // Auto-provision personal workspace if user has none
        $slug = Str::slug($user->name);
        $slug = $slug !== '' ? $slug.'-'.Str::lower(Str::random(6)) : 'workspace-'.Str::lower(Str::random(8));

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => $user->name."'s Workspace",
            'slug' => $slug,
            'owner_id' => $user->id,
        ]);

        $workspace->members()->attach($user->id, ['role' => 'owner']);

        session(['current_workspace_id' => $workspace->id]);

        return redirect()->route('workspaces.show', $workspace);
    }
}
