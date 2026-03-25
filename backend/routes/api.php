<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\ContentSuggestionController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\Plugin\HeartbeatController;
use App\Http\Controllers\Plugin\PluginController;
use App\Http\Controllers\Plugin\PostPublishedController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

// ── Public Auth Routes ────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', RegisterController::class);
    Route::post('/login', LoginController::class);
    Route::delete('/logout', [LoginController::class, 'logout'])->middleware('auth:sanctum');

    Route::get('/google/redirect', [GoogleController::class, 'redirect']);
    Route::get('/google/callback', [GoogleController::class, 'callback']);
});

// ── Public Plugin Endpoints (verified by API key, not Sanctum) ───────────────
Route::prefix('plugin')->group(function () {
    Route::post('/verify',       [PluginController::class, 'verify']);
    Route::post('/sync',         [PluginController::class, 'sync']);
    Route::get('/version',       [PluginController::class, 'version']);
    Route::get('/download',      [PluginController::class, 'download']);
    Route::post('/heartbeat',    [HeartbeatController::class, 'handle']);
    Route::post('/post-published', [PostPublishedController::class, 'handle']);
});

// ── Stripe Webhook (no auth — verified by signature) ─────────────────────────
Route::post('/webhooks/stripe', [BillingController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

// ── Authenticated Routes ──────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Current user
    Route::get('/me', fn(\Illuminate\Http\Request $r) => response()->json(['user' => $r->user()]));

    // Notifications
    Route::get('/notifications',                         [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all',              [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read',             [NotificationController::class, 'markRead']);
    Route::get('/notifications/unread-count',            [NotificationController::class, 'unreadCount']);

    // Workspaces
    Route::apiResource('workspaces', WorkspaceController::class)->only(['index', 'show', 'update']);

    // ── Workspace-scoped routes ───────────────────────────────────────────────
    Route::prefix('workspaces/{workspace}')
        ->middleware(['workspace'])
        ->group(function () {

            // ── Sites ─────────────────────────────────────────────────────────
            Route::apiResource('sites', SiteController::class)->except(['show']);
            Route::get('sites/{site}',                           [SiteController::class, 'show']);
            Route::post('sites/{site}/verify-connection',        [SiteController::class, 'verifyConnection']);
            Route::post('sites/{site}/plugin/generate-key',     [PluginController::class, 'generateKey']);

            // ── Competitors ────────────────────────────────────────────────────
            Route::apiResource('sites/{site}/competitors', CompetitorController::class);
            Route::post('sites/{site}/competitors/{competitor}/scan',    [CompetitorController::class, 'scan']);
            Route::post('sites/{site}/competitors/{competitor}/toggle',  [CompetitorController::class, 'toggleAutoSpy']);

            // ── Content Suggestions ────────────────────────────────────────────
            Route::get('suggestions',                        [ContentSuggestionController::class, 'index']);
            Route::post('suggestions/{suggestion}/accept',   [ContentSuggestionController::class, 'accept']);
            Route::post('suggestions/{suggestion}/reject',   [ContentSuggestionController::class, 'reject']);

            // ── Articles ───────────────────────────────────────────────────────
            Route::apiResource('articles', ArticleController::class);
            Route::post('articles/{article}/generate',  [ArticleController::class, 'generate']);
            Route::post('articles/{article}/publish',   [ArticleController::class, 'publish']);
            Route::post('articles/{article}/approve',   [ArticleController::class, 'approve']);
            Route::post('articles/{article}/reject',    [ArticleController::class, 'reject']);
            Route::post('articles/{article}/retry',     [ArticleController::class, 'retry']);

            // ── Social Media ───────────────────────────────────────────────────
            Route::get('social/accounts',                   [SocialController::class, 'accounts']);
            Route::delete('social/accounts/{account}',      [SocialController::class, 'disconnect']);
            Route::post('articles/{article}/social/publish', [SocialController::class, 'publishArticle']);
            Route::get('/auth/social/{platform}/redirect',   [SocialController::class, 'oauthRedirect']);
            Route::get('/auth/social/{platform}/callback',   [SocialController::class, 'oauthCallback']);

            // ── Credits ────────────────────────────────────────────────────────
            Route::get('credits',              [CreditController::class, 'index']);
            Route::get('credits/transactions', [CreditController::class, 'transactions']);

            // ── Billing ────────────────────────────────────────────────────────
            Route::post('billing/subscribe',   [BillingController::class, 'subscribe']);
            Route::post('billing/buy-credits', [BillingController::class, 'buyCredits']);
            Route::post('billing/cancel',      [BillingController::class, 'cancel']);

            // ── Analytics ─────────────────────────────────────────────────────
            Route::get('analytics/overview',       [AnalyticsController::class, 'overview']);
            Route::get('analytics/token-usage',    [AnalyticsController::class, 'tokenUsage']);
            Route::get('analytics/articles',       [AnalyticsController::class, 'articles']);
            Route::get('analytics/spy',            [AnalyticsController::class, 'spy']);
        });
});
