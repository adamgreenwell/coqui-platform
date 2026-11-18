<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectCompetitorController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SanctumTokenController;
use App\Http\Controllers\TrackedKeywordController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appName' => config('app.name'),
        'domain' => 'coquiseo.com',
    ]);
})->name('home');

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::resource('workspaces', WorkspaceController::class);

    Route::get('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index'])->name('workspaces.members.index');
    Route::post('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store'])->name('workspaces.members.store');
    Route::put('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'update'])->name('workspaces.members.update');
    Route::delete('/workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy'])->name('workspaces.members.destroy');

    Route::get('/workspaces/{workspace}/tokens', [SanctumTokenController::class, 'index'])->name('workspaces.tokens.index');
    Route::post('/workspaces/{workspace}/tokens', [SanctumTokenController::class, 'store'])->name('workspaces.tokens.store');
    Route::delete('/workspaces/{workspace}/tokens/{token}', [SanctumTokenController::class, 'destroy'])->name('workspaces.tokens.destroy');

    Route::get('/workspaces/{workspace}/projects/create', [ProjectController::class, 'create'])->name('workspaces.projects.create');
    Route::post('/workspaces/{workspace}/projects', [ProjectController::class, 'store'])->name('workspaces.projects.store');
    Route::get('/workspaces/{workspace}/projects/{project}', [ProjectController::class, 'show'])->name('workspaces.projects.show');
    Route::get('/workspaces/{workspace}/projects/{project}/activities', [ProjectController::class, 'activities'])->name('workspaces.projects.activities');
    Route::get('/workspaces/{workspace}/projects/{project}/settings', [ProjectController::class, 'edit'])->name('workspaces.projects.settings');
    Route::put('/workspaces/{workspace}/projects/{project}', [ProjectController::class, 'update'])->name('workspaces.projects.update');
    Route::delete('/workspaces/{workspace}/projects/{project}', [ProjectController::class, 'destroy'])->name('workspaces.projects.destroy');

    Route::post('/workspaces/{workspace}/projects/{project}/competitors', [ProjectCompetitorController::class, 'store'])->name('workspaces.projects.competitors.store');
    Route::post('/workspaces/{workspace}/projects/{project}/competitors/reorder', [ProjectCompetitorController::class, 'reorder'])->name('workspaces.projects.competitors.reorder');
    Route::delete('/workspaces/{workspace}/projects/{project}/competitors/{competitor}', [ProjectCompetitorController::class, 'destroy'])->name('workspaces.projects.competitors.destroy');

    Route::get('/workspaces/{workspace}/projects/{project}/keywords', [TrackedKeywordController::class, 'index'])->name('workspaces.projects.keywords.index');
    Route::post('/workspaces/{workspace}/projects/{project}/keywords', [TrackedKeywordController::class, 'store'])->name('workspaces.projects.keywords.store');
    Route::delete('/workspaces/{workspace}/projects/{project}/keywords/{keyword}', [TrackedKeywordController::class, 'destroy'])->name('workspaces.projects.keywords.destroy');
    Route::get('/workspaces/{workspace}/projects/{project}/keywords/{keyword}/snapshots', [TrackedKeywordController::class, 'snapshots'])->name('workspaces.projects.keywords.snapshots');
});
