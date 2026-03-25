<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\Credits\CreditService;
use App\Models\Workspace;
use App\Models\CreditTransaction;
use App\Services\Credits\InsufficientCreditsException;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreditService $creditService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creditService = app(CreditService::class);
    }

    public function test_reserve_credits_success(): void
    {
        $workspace = Workspace::factory()->create([
            'credits_balance' => 100,
            'credits_reserved' => 0,
        ]);

        $reservation = $this->creditService->reserve($workspace, 'test_action', 10);

        $this->assertNotNull($reservation);
        $this->assertEquals(10, $reservation->amount);
        $this->assertEquals('test_action', $reservation->action_type);
        
        // Check reserved amount updated
        $workspace->refresh();
        $this->assertEquals(10, $workspace->credits_reserved);
    }

    public function test_reserve_fails_with_insufficient_credits(): void
    {
        $workspace = Workspace::factory()->create([
            'credits_balance' => 50,
            'credits_reserved' => 0,
        ]);

        $this->expectException(InsufficientCreditsException::class);
        
        $this->creditService->reserve($workspace, 'test_action', 60);
    }

    public function test_reserve_fails_when_balance_minus_reserved_less_than_amount(): void
    {
        $workspace = Workspace::factory()->create([
            'credits_balance' => 100,
            'credits_reserved' => 80, // Only 20 available
        ]);

        $this->expectException(InsufficientCreditsException::class);
        
        $this->creditService->reserve($workspace, 'test_action', 30);
    }

    public function test_confirm_reservation_deducts_credits(): void
    {
        $workspace = Workspace::factory()->create([
            'credits_balance' => 100,
            'credits_reserved' => 0,
        ]);

        $reservation = $this->creditService->reserve($workspace, 'article_generation', 5);
        
        $this->creditService->confirm($workspace, 'article_generation');

        $workspace->refresh();
        $this->assertEquals(95, $workspace->credits_balance);
        $this->assertEquals(0, $workspace->credits_reserved);

        // Verify transaction recorded
        $this->assertDatabaseHas('credit_transactions', [
            'workspace_id' => $workspace->id,
            'type' => 'debit',
            'amount' => -5,
            'action_type' => 'article_generation',
        ]);
    }

    public function test_refund_reservation_releases_without_deduction(): void
    {
        $workspace = Workspace::factory()->create([
            'credits_balance' => 100,
            'credits_reserved' => 0,
        ]);

        $reservation = $this->creditService->reserve($workspace, 'test_action', 10);
        
        $this->creditService->refund($workspace, 'test_action');

        $workspace->refresh();
        $this->assertEquals(100, $workspace->credits_balance);
        $this->assertEquals(0, $workspace->credits_reserved);
    }

    public function test_concurrent_reservations_cannot_overdraft(): void
    {
        $workspace = Workspace::factory()->create([
            'credits_balance' => 50,
            'credits_reserved' => 0,
        ]);

        // Simulate concurrent reservations
        $reservation1 = $this->creditService->reserve($workspace, 'action1', 30);
        $reservation2 = $this->creditService->reserve($workspace, 'action2', 30);

        // Second reservation should fail as only 20 credits remain
        $this->assertNull($reservation2);

        // First reservation should still work
        $this->assertNotNull($reservation1);
    }

    public function test_balance_retrieved_from_last_transaction(): void
    {
        $workspace = Workspace::factory()->create([
            'credits_balance' => 0,
            'credits_reserved' => 0,
        ]);

        // Create initial balance transaction
        CreditTransaction::create([
            'workspace_id' => $workspace->id,
            'type' => 'purchase',
            'amount' => 500,
            'balance_after' => 500,
            'description' => 'Credit pack purchase',
        ]);

        // Update workspace balance
        $workspace->update(['credits_balance' => 500]);

        $balance = $this->creditService->getBalance($workspace);
        
        $this->assertEquals(500, $balance);
    }
}