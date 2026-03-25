<?php

namespace App\Services\Workflow;

use App\Models\Site;
use App\Models\SpyDetection;
use App\Models\ContentSuggestion;
use App\Services\Content\ContentPipelineOrchestrator;
use App\Services\Publishing\WordPressPublisher;
use App\Services\Social\SocialPublisherOrchestrator;
use Illuminate\Support\Facades\Log;

class WorkflowEngine
{
    public function __construct(
        private readonly ContentPipelineOrchestrator $contentPipeline,
        private readonly WordPressPublisher $wordPressPublisher,
        private readonly SocialPublisherOrchestrator $socialPublisher,
    ) {}

    /**
     * Execute the configured workflow for a site
     * Triggered when: spy detection, manual trigger, schedule, webhook
     */
    public function run(Site $site, string $trigger, ?SpyDetection $detection = null): void
    {
        $workspace = $site->workspace;
        $workflowTemplate = $site->workflow_template ?? 'human_in_the_loop';
        
        Log::info('[WorkflowEngine] Starting workflow', [
            'site_id' => $site->id,
            'template' => $workflowTemplate,
            'trigger' => $trigger,
        ]);

        // Log workflow start
        $this->logStep($site, $trigger, 'workflow_start', 'started', null);

        // Execute based on template
        match($workflowTemplate) {
            'full_autopilot' => $this->runFullAutopilot($site, $trigger, $detection),
            'human_in_the_loop' => $this->runHumanInTheLoop($site, $trigger, $detection),
            'spy_only' => $this->runSpyOnly($site, $trigger, $detection),
            'generate_only' => $this->runGenerateOnly($site, $trigger, $detection),
            'social_only' => $this->runSocialOnly($site, $trigger, $detection),
            default => Log::warning('[WorkflowEngine] Unknown template', ['template' => $workflowTemplate]),
        };
    }

    /**
     * Full Autopilot: detect → suggest → generate → publish → distribute (all auto)
     */
    private function runFullAutopilot(Site $site, string $trigger, ?SpyDetection $detection): void
    {
        // Check credits - full autopilot requires sufficient credits
        if ($site->workspace->credits_balance < 100) {
            $this->logStep($site, $trigger, 'autopilot_check', 'skipped', 'Insufficient credits');
            return;
        }

        // Step 1: If detection provided, generate suggestion
        $suggestion = null;
        if ($detection) {
            $suggestion = $this->autoGenerateSuggestion($detection);
            if (!$suggestion || $suggestion->opportunity_score < $site->confidence_threshold_suggest) {
                $this->logStep($site, $trigger, 'suggestion', 'skipped', 'Low opportunity score');
                return;
            }
            $this->logStep($site, $trigger, 'suggestion', 'completed', ['suggestion_id' => $suggestion->id]);
        }

        // Step 2: Generate article (if suggestion exists)
        $article = null;
        if ($suggestion && $suggestion->opportunity_score >= $site->confidence_threshold_generate) {
            $article = $this->contentPipeline->start($suggestion);
            $this->logStep($site, $trigger, 'generation', 'completed', ['article_id' => $article->id]);
        }

        // Step 3: Publish to WordPress (if article ready)
        if ($article && $article->generation_status === 'ready') {
            if ($article->opportunity_score >= $site->confidence_threshold_publish) {
                $this->wordPressPublisher->publish($article, $site);
                $this->logStep($site, $trigger, 'publish', 'completed', ['wp_post_id' => $article->wp_post_id]);
            }
        }

        // Step 4: Distribute to social (if published)
        if ($article && $article->wp_post_id) {
            $this->socialPublisher->publishForArticle($article, $site);
            $this->logStep($site, trigger: $trigger, 'distribute', 'completed', null);
        }
    }

    /**
     * Human in the Loop: detect → suggest (auto) → generate (auto) → PAUSE → publish (manual)
     */
    private function runHumanInTheLoop(Site $site, string $trigger, ?SpyDetection $detection): void
    {
        // Step 1: Generate suggestion automatically
        if ($detection) {
            $suggestion = $this->autoGenerateSuggestion($detection);
            $this->logStep($site, $trigger, 'suggestion', 'completed', ['suggestion_id' => $suggestion?->id]);
        }

        // Step 2: Generate article automatically
        // The pipeline will pause at 'review' status waiting for human approval
        
        // This is handled by the SuggestionController when user accepts a suggestion
    }

    /**
     * Spy Only: detect → suggest (auto) → STOP
     */
    private function runSpyOnly(Site $site, string $trigger, ?SpyDetection $detection): void
    {
        if ($detection) {
            $suggestion = $this->autoGenerateSuggestion($detection);
            $this->logStep($site, $trigger, 'suggestion', 'completed', ['suggestion_id' => $suggestion?->id]);
        }
    }

    /**
     * Generate Only: no spy, manual brief input
     */
    private function runGenerateOnly(Site $site, string $trigger, ?SpyDetection $detection): void
    {
        // This is triggered when user manually creates a suggestion without spy detection
    }

    /**
     * Social Only: generate + publish to social only, skip WordPress
     */
    private function runSocialOnly(Site $site, string $trigger, ?SpyDetection $detection): void
    {
        // Similar to full autopilot but skip WordPress publishing
    }

    /**
     * Auto-generate content suggestion from detection
     */
    private function autoGenerateSuggestion(SpyDetection $detection): ?ContentSuggestion
    {
        // This would dispatch GenerateSuggestionJob
        // For now, return null - actual implementation uses the job
        return null;
    }

    /**
     * Log workflow step
     */
    private function logStep(Site $site, string $trigger, string $step, string $status, ?array $data): void
    {
        \App\Models\WorkflowLog::create([
            'site_id' => $site->id,
            'workspace_id' => $site->workspace_id,
            'trigger' => $trigger,
            'step' => $step,
            'status' => $status,
            'input' => $data,
            'output' => null,
        ]);
    }
}