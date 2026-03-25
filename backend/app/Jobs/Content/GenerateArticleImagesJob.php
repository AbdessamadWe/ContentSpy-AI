<?php
namespace App\Jobs\Content;

use App\Models\Article;
use App\Models\TokenUsageLog;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateArticleImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;

    public function __construct(public readonly int $articleId) {}

    public function handle(CreditService $credits): void
    {
        $article = Article::with('site')->find($this->articleId);
        if (!$article) return;

        $workspace = Workspace::find($article->workspace_id);
        $imageProvider = $article->ai_model_image ?? $article->site->ai_model_image ?? 'dalle-3';

        $creditAction = match($imageProvider) {
            'midjourney'         => 'image_midjourney',
            'stable-diffusion-xl' => 'image_stable_diffusion',
            default              => 'image_dalle3',
        };

        $creditCost = config("credits.actions.{$creditAction}", 3);
        $token = $credits->reserve($workspace, $creditCost, $creditAction);

        try {
            $imageUrl = $this->generateImage($article->title, $imageProvider);

            if ($imageUrl) {
                $article->update(['featured_image_url' => $imageUrl]);

                // Record image cost
                TokenUsageLog::create([
                    'workspace_id'  => $workspace->id,
                    'site_id'       => $article->site_id,
                    'action_type'   => $creditAction,
                    'model'         => $imageProvider,
                    'provider'      => $imageProvider === 'dalle-3' ? 'dalle' : ($imageProvider === 'midjourney' ? 'midjourney' : 'stability'),
                    'images_count'  => 1,
                    'cost_usd'      => \App\Services\AI\TokenCostCalculator::calculateImageCost($imageProvider),
                    'credits_consumed' => $creditCost,
                    'article_id'    => $article->id,
                ]);
            }

            $article->advancePipelineStep('review');
            $credits->confirm($workspace, $token, actionId: (string) $article->id);
        } catch (\Throwable $e) {
            $credits->refund($workspace, $token, $e->getMessage());
            Log::error("[ImageGen] Article #{$article->id}: " . $e->getMessage());
            // Don't fail the whole article for image failure — advance to review anyway
            $article->advancePipelineStep('review');
        }
    }

    private function generateImage(string $prompt, string $provider): ?string
    {
        return match($provider) {
            'dalle-3' => $this->generateDalle3($prompt),
            'stable-diffusion-xl' => $this->generateStableDiffusion($prompt),
            default   => null,
        };
    }

    private function generateDalle3(string $prompt): ?string
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(60)
            ->post('https://api.openai.com/v1/images/generations', [
                'model'   => 'dall-e-3',
                'prompt'  => "Blog featured image: {$prompt}. Professional, high quality, suitable for a blog post.",
                'n'       => 1,
                'size'    => '1024x1024',
            ]);

        if (!$response->successful()) return null;
        return $response->json('data.0.url');
    }

    private function generateStableDiffusion(string $prompt): ?string
    {
        $response = Http::withToken(config('services.replicate.key'))
            ->timeout(60)
            ->post('https://api.replicate.com/v1/predictions', [
                'version' => 'ac732df83cea7fff18b8472768c88ad041fa750465f4e0500803cf9abfc78fd0',
                'input'   => ['prompt' => "Blog featured image: {$prompt}. Professional photography style."],
            ]);

        if (!$response->successful()) return null;

        // Replicate is async — poll for result
        $predictionId = $response->json('id');
        for ($i = 0; $i < 30; $i++) {
            sleep(2);
            $status = Http::withToken(config('services.replicate.key'))
                ->get("https://api.replicate.com/v1/predictions/{$predictionId}")
                ->json();

            if ($status['status'] === 'succeeded') return $status['output'][0] ?? null;
            if ($status['status'] === 'failed') return null;
        }

        return null;
    }

    public function tags(): array
    {
        return ["article:{$this->articleId}", 'image-generation'];
    }
}
