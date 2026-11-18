<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SanctumTokenController;
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
});
