<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\Site;
use App\Models\Competitor;
use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_user_with_workspace_and_credits(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'user' => ['id', 'email', 'name'],
            'token',
            'workspace' => ['id', 'name', 'plan', 'credits_balance'],
        ]);

        // Verify user created
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        // Verify workspace created with 500 credits (Starter plan)
        $this->assertDatabaseHas('workspaces', [
            'owner_id' => User::where('email', 'test@example.com')->first()->id,
            'plan' => 'starter',
            'credits_balance' => 500,
        ]);

        // Verify credit transaction recorded
        $this->assertDatabaseHas('credit_transactions', [
            'type' => 'plan_grant',
            'amount' => 500,
        ]);
    }

    public function test_login_returns_token(): void
    {
        $user = User::factory()->create();
        
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token']);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create();
        
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }
}

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private User $userB;
    private Workspace $workspaceA;
    private Workspace $workspaceB;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
        
        $this->workspaceA = Workspace::factory()->create(['owner_id' => $this->userA->id]);
        $this->workspaceB = Workspace::factory()->create(['owner_id' => $this->userB->id]);
        
        $this->workspaceA->users()->attach($this->userA->id, ['role' => 'owner']);
        $this->workspaceB->users()->attach($this->userB->id, ['role' => 'owner']);
    }

    public function test_user_cannot_access_another_workspace_sites(): void
    {
        $siteA = Site::factory()->create(['workspace_id' => $this->workspaceA->id]);
        
        $this->actingAs($this->userB);
        
        $response = $this->getJson("/api/sites/{$siteA->id}");
        
        $response->assertStatus(403);
    }

    public function test_user_cannot_access_another_workspace_competitors(): void
    {
        $siteA = Site::factory()->create(['workspace_id' => $this->workspaceA->id]);
        $competitorA = Competitor::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'site_id' => $siteA->id,
        ]);
        
        $this->actingAs($this->userB);
        
        $response = $this->getJson("/api/competitors/{$competitorA->id}");
        
        $response->assertStatus(403);
    }

    public function test_user_cannot_access_another_workspace_articles(): void
    {
        $siteA = Site::factory()->create(['workspace_id' => $this->workspaceA->id]);
        $articleA = Article::factory()->create([
            'workspace_id' => $this->workspaceA->id,
            'site_id' => $siteA->id,
        ]);
        
        $this->actingAs($this->userB);
        
        $response = $this->getJson("/api/articles/{$articleA->id}");
        
        $response->assertStatus(403);
    }

    public function test_user_can_access_own_workspace_resources(): void
    {
        $siteA = Site::factory()->create(['workspace_id' => $this->workspaceA->id]);
        
        $this->actingAs($this->userA);
        
        $response = $this->getJson("/api/sites");
        
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'sites');
    }
}

class SpyDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_article_from_different_methods_creates_single_detection(): void
    {
        $workspace = Workspace::factory()->create();
        $site = Site::factory()->create(['workspace_id' => $workspace->id]);
        $competitor = Competitor::factory()->create([
            'workspace_id' => $workspace->id,
            'site_id' => $site->id,
        ]);

        // First detection via RSS
        $detection1 = \App\Models\SpyDetection::create([
            'competitor_id' => $competitor->id,
            'site_id' => $site->id,
            'workspace_id' => $workspace->id,
            'method' => 'rss',
            'source_url' => 'https://example.com/article-1',
            'title' => 'Test Article',
            'content_hash' => hash('sha256', 'https://example.com/article-1' . 'Test Article'),
        ]);

        // Second detection via HTML scraping (same content)
        $detection2 = \App\Models\SpyDetection::create([
            'competitor_id' => $competitor->id,
            'site_id' => $site->id,
            'workspace_id' => $workspace->id,
            'method' => 'html_scraping',
            'source_url' => 'https://example.com/article-1',
            'title' => 'Test Article',
            'content_hash' => hash('sha256', 'https://example.com/article-1' . 'Test Article'),
        ]);

        // Count should be 1 due to unique content_hash constraint
        $count = \App\Models\SpyDetection::where('content_hash', $detection1->content_hash)->count();
        
        $this->assertEquals(1, $count);
    }
}