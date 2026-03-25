<?php
namespace App\Jobs\Content;

use App\Models\SpyDetection;
use App\Services\Content\SuggestionGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateSuggestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(private readonly int $detectionId) {}

    public function backoff(): array
    {
        return [30, 60];
    }

    public function handle(SuggestionGeneratorService $service): void
    {
        $detection = SpyDetection::find($this->detectionId);

        if (! $detection) {
            Log::warning("[GenerateSuggestionJob] Detection #{$this->detectionId} not found — skipping");
            return;
        }

        $suggestion = $service->fromDetection($detection);

        if ($suggestion) {
            Log::info("[GenerateSuggestionJob] Created suggestion #{$suggestion->id} from detection #{$this->detectionId}");
        } else {
            Log::info("[GenerateSuggestionJob] Skipped detection #{$this->detectionId} (duplicate)");
        }
    }
}
