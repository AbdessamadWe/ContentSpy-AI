<?php
namespace App\Services\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications via multiple channels:
 * - Database (in-app)
 * - Email via Resend
 * - Pusher/Soketi for real-time
 * - Slack webhook
 */
class NotificationService
{
    // ─── In-app (Database) ───────────────────────────────────────────────────

    /**
     * Store an in-app notification for a user.
     */
    public function notify(User $user, string $type, string $message, array $data = []): void
    {
        $user->notifications()->create([
            'type'    => $type,
            'data'    => array_merge(['message' => $message], $data),
            'read_at' => null,
        ]);

        // Broadcast real-time via Pusher/Soketi
        $this->broadcast($user, $type, $message, $data);
    }

    // ─── Real-time (Pusher/Soketi) ───────────────────────────────────────────

    private function broadcast(User $user, string $type, string $message, array $data): void
    {
        try {
            broadcast(new \App\Events\UserNotification($user->id, $type, $message, $data));
        } catch (\Throwable $e) {
            Log::warning("[NotificationService] Broadcast failed: {$e->getMessage()}");
        }
    }

    // ─── Email (Resend) ──────────────────────────────────────────────────────

    /**
     * Send transactional email via Resend API.
     */
    public function sendEmail(string $to, string $subject, string $htmlContent, ?string $fromName = null): bool
    {
        try {
            $response = Http::withToken(config('services.resend.api_key'))
                ->timeout(15)
                ->post('https://api.resend.com/emails', [
                    'from'    => ($fromName ?? config('app.name')) . ' <' . config('mail.from.address') . '>',
                    'to'      => [$to],
                    'subject' => $subject,
                    'html'    => $htmlContent,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("[NotificationService] Email send failed: {$e->getMessage()}");
            return false;
        }
    }

    // ─── Slack ───────────────────────────────────────────────────────────────

    /**
     * Send a Slack webhook notification to workspace's configured webhook URL.
     */
    public function sendSlack(Workspace $workspace, string $message, array $blocks = []): bool
    {
        $webhookUrl = $workspace->settings['slack_webhook_url'] ?? null;
        if (! $webhookUrl) return false;

        try {
            $payload = ['text' => $message];
            if ($blocks) $payload['blocks'] = $blocks;

            $response = Http::timeout(10)->post($webhookUrl, $payload);
            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("[NotificationService] Slack send failed: {$e->getMessage()}");
            return false;
        }
    }

    // ─── Outgoing Webhooks ───────────────────────────────────────────────────

    /**
     * Deliver event to all active webhook endpoints for a workspace.
     * Payload is HMAC-signed with each endpoint's secret.
     */
    public function deliverWebhook(Workspace $workspace, string $event, array $payload): void
    {
        $endpoints = \App\Models\WebhookEndpoint::where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->where('failure_count', '<', 10)
            ->whereJsonContains('events', $event)
            ->get();

        foreach ($endpoints as $endpoint) {
            \App\Jobs\DeliverWebhookJob::dispatch($endpoint->id, $event, $payload)
                ->onQueue('webhooks');
        }
    }

    // ─── Convenience Alerts ──────────────────────────────────────────────────

    public function articleGenerated(User $user, int $articleId, string $title): void
    {
        $this->notify($user, 'article_generated', "Article ready: {$title}", [
            'article_id' => $articleId,
            'action_url' => "/articles/{$articleId}",
        ]);
    }

    public function articleFailed(User $user, int $articleId, string $title, string $reason): void
    {
        $this->notify($user, 'article_failed', "Article generation failed: {$title}", [
            'article_id' => $articleId,
            'reason'     => $reason,
        ]);
    }

    public function newSuggestions(User $user, int $count): void
    {
        $this->notify($user, 'new_suggestions', "{$count} new content suggestion" . ($count !== 1 ? 's' : '') . " detected", [
            'count'      => $count,
            'action_url' => '/suggestions',
        ]);
    }

    public function lowCredits(User $user, Workspace $workspace, int $balance): void
    {
        $this->notify($user, 'low_credits', "Credits running low: {$balance} remaining", [
            'balance'    => $balance,
            'action_url' => '/billing',
        ]);

        $this->sendSlack($workspace, ":warning: ContentSpy: Workspace *{$workspace->name}* has only {$balance} credits remaining.");
    }
}
