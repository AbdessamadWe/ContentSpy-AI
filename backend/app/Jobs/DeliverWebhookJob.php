<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(
        private readonly int    $endpointId,
        private readonly string $event,
        private readonly array  $payload,
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900]; // 1m, 5m, 15m
    }

    public function handle(): void
    {
        $endpoint = \App\Models\WebhookEndpoint::find($this->endpointId);
        if (! $endpoint || ! $endpoint->is_active) return;

        $body      = json_encode([
            'event'     => $this->event,
            'data'      => $this->payload,
            'timestamp' => now()->toIso8601String(),
        ]);
        $secret    = decrypt($endpoint->secret);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        try {
            Http::withHeaders([
                'X-ContentSpy-Event'     => $this->event,
                'X-ContentSpy-Signature' => $signature,
                'Content-Type'           => 'application/json',
            ])
            ->timeout(15)
            ->withBody($body, 'application/json')
            ->post($endpoint->url)
            ->throw();

            $endpoint->update(['failure_count' => 0, 'last_triggered_at' => now()]);

        } catch (\Throwable $e) {
            $endpoint->increment('failure_count');

            // Auto-disable after 10 consecutive failures
            if ($endpoint->failure_count >= 10) {
                $endpoint->update(['is_active' => false]);
                Log::warning("[Webhook] Auto-disabled endpoint #{$endpoint->id} after 10 failures");
            }

            Log::error("[Webhook] Delivery failed to {$endpoint->url}: {$e->getMessage()}");
            throw $e;
        }
    }
}
