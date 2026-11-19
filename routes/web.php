<?php

declare(strict_types=1);

use App\Http\Controllers\BacklinkController;
use App\Http\Controllers\BacklinkIntersectController;
use App\Http\Controllers\BacklinkWatchlistController;
use App\Http\Controllers\BotTelemetryController;
use App\Http\Controllers\CloudCrawlController;
use App\Http\Controllers\ContentBriefController;
use App\Http\Controllers\CrawlCompareController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KeywordExplorerController;
use App\Http\Controllers\KeywordGapController;
use App\Http\Controllers\ProjectCompetitorController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectIntegrationController;
use App\Http\Controllers\PublicCrawlDiffController;
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

    Route::get('/workspaces/{workspace}/projects/{project}/keywords/brief', [ContentBriefController::class, 'show'])->name('workspaces.projects.keywords.brief');
    Route::post('/workspaces/{workspace}/projects/{project}/keywords/brief', [ContentBriefController::class, 'generate'])->name('workspaces.projects.keywords.brief.generate');
    Route::get('/workspaces/{workspace}/projects/{project}/keywords/brief/download', [ContentBriefController::class, 'download'])->name('workspaces.projects.keywords.brief.download');

    Route::get('/workspaces/{workspace}/projects/{project}/keyword-explorer', [KeywordExplorerController::class, 'index'])->name('workspaces.projects.keyword-explorer.index');
    Route::post('/workspaces/{workspace}/projects/{project}/keyword-explorer/overview', [KeywordExplorerController::class, 'overview'])->name('workspaces.projects.keyword-explorer.overview');
    Route::post('/workspaces/{workspace}/projects/{project}/keyword-explorer/related', [KeywordExplorerController::class, 'related'])->name('workspaces.projects.keyword-explorer.related');
    Route::post('/workspaces/{workspace}/projects/{project}/keyword-explorer/track', [KeywordExplorerController::class, 'track'])->name('workspaces.projects.keyword-explorer.track');

    Route::get('/workspaces/{workspace}/projects/{project}/keyword-gap', [KeywordGapController::class, 'index'])->name('workspaces.projects.keyword-gap.index');
    Route::post('/workspaces/{workspace}/projects/{project}/keyword-gap/search', [KeywordGapController::class, 'search'])->name('workspaces.projects.keyword-gap.search');
    Route::post('/workspaces/{workspace}/projects/{project}/keyword-gap/track', [KeywordGapController::class, 'track'])->name('workspaces.projects.keyword-gap.track');

    Route::get('/workspaces/{workspace}/projects/{project}/backlinks', [BacklinkController::class, 'index'])->name('workspaces.projects.backlinks.index');
    Route::post('/workspaces/{workspace}/projects/{project}/backlinks/refresh', [BacklinkController::class, 'refresh'])->name('workspaces.projects.backlinks.refresh');
    Route::get('/workspaces/{workspace}/projects/{project}/backlinks/watchlist', [BacklinkWatchlistController::class, 'index'])->name('workspaces.projects.backlinks.watchlist.index');
    Route::post('/workspaces/{workspace}/projects/{project}/backlinks/watchlist', [BacklinkWatchlistController::class, 'store'])->name('workspaces.projects.backlinks.watchlist.store');
    Route::post('/workspaces/{workspace}/projects/{project}/backlinks/watchlist/verify', [BacklinkWatchlistController::class, 'verifyAll'])->name('workspaces.projects.backlinks.watchlist.verify-all');
    Route::post('/workspaces/{workspace}/projects/{project}/backlinks/watchlist/{monitoredBacklink}/verify', [BacklinkWatchlistController::class, 'verifySingle'])->name('workspaces.projects.backlinks.watchlist.verify-single');
    Route::post('/workspaces/{workspace}/projects/{project}/backlinks/watchlist/import-dfs', [BacklinkWatchlistController::class, 'importDfs'])->name('workspaces.projects.backlinks.watchlist.import-dfs');
    Route::post('/workspaces/{workspace}/projects/{project}/backlinks/watchlist/import-csv', [BacklinkWatchlistController::class, 'importCsv'])->name('workspaces.projects.backlinks.watchlist.import-csv');
    Route::delete('/workspaces/{workspace}/projects/{project}/backlinks/watchlist/{monitoredBacklink}', [BacklinkWatchlistController::class, 'destroy'])->name('workspaces.projects.backlinks.watchlist.destroy');

    Route::get('/workspaces/{workspace}/projects/{project}/backlinks/intersect', [BacklinkIntersectController::class, 'index'])->name('workspaces.projects.backlinks.intersect.index');
    Route::post('/workspaces/{workspace}/projects/{project}/backlinks/intersect/refresh', [BacklinkIntersectController::class, 'refresh'])->name('workspaces.projects.backlinks.intersect.refresh');

    Route::get('/workspaces/{workspace}/projects/{project}/integrations', [ProjectIntegrationController::class, 'index'])->name('workspaces.projects.integrations.index');
    Route::get('/workspaces/{workspace}/projects/{project}/integrations/google/connect', [ProjectIntegrationController::class, 'connectGoogle'])->name('workspaces.projects.integrations.google.connect');
    Route::get('/workspaces/{workspace}/projects/{project}/integrations/google/callback', [ProjectIntegrationController::class, 'callbackGoogle'])->name('workspaces.projects.integrations.google.callback');
    Route::post('/workspaces/{workspace}/projects/{project}/integrations/bing/connect', [ProjectIntegrationController::class, 'connectBing'])->name('workspaces.projects.integrations.bing.connect');
    Route::post('/workspaces/{workspace}/projects/{project}/integrations/runnr/connect', [ProjectIntegrationController::class, 'connectRunnr'])->name('workspaces.projects.integrations.runnr.connect');
    Route::delete('/workspaces/{workspace}/projects/{project}/integrations/runnr/disconnect', [ProjectIntegrationController::class, 'disconnectRunnr'])->name('workspaces.projects.integrations.runnr.disconnect');
    Route::post('/workspaces/{workspace}/projects/{project}/integrations/{integration}/sync', [ProjectIntegrationController::class, 'sync'])->name('workspaces.projects.integrations.sync');
    Route::delete('/workspaces/{workspace}/projects/{project}/integrations/{integration}', [ProjectIntegrationController::class, 'destroy'])->name('workspaces.projects.integrations.destroy');

    Route::get('/workspaces/{workspace}/projects/{project}/bot-logs', [BotTelemetryController::class, 'index'])->name('workspaces.projects.bot-logs.index');
    Route::post('/workspaces/{workspace}/projects/{project}/bot-logs/remediate', [BotTelemetryController::class, 'remediate'])->name('workspaces.projects.bot-logs.remediate');

    Route::get('/workspaces/{workspace}/projects/{project}/crawls/compare', [CrawlCompareController::class, 'show'])->name('workspaces.projects.crawls.compare');
    Route::post('/workspaces/{workspace}/projects/{project}/crawls/compare/share', [CrawlCompareController::class, 'share'])->name('workspaces.projects.crawls.compare.share');
    Route::post('/workspaces/{workspace}/projects/{project}/crawls/dispatch', [CloudCrawlController::class, 'dispatch'])->name('workspaces.projects.crawls.dispatch');
});

Route::get('/reports/crawls/diff/{shareToken}', [PublicCrawlDiffController::class, 'show'])->name('reports.crawls.diff');
