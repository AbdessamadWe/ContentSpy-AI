<?php
namespace App\Http\Controllers;

use App\Services\Billing\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(private readonly StripeService $stripe) {}

    /**
     * POST /api/billing/subscribe
     * Create a subscription checkout session.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate(['plan' => 'required|in:starter,pro,agency']);

        $workspace = $request->attributes->get('_workspace');
        $url       = $this->stripe->createSubscriptionCheckout(
            $workspace,
            $request->plan,
            $request->user()->email,
        );

        return response()->json(['checkout_url' => $url]);
    }

    /**
     * POST /api/billing/buy-credits
     * Create a one-time credit pack checkout session.
     */
    public function buyCredits(Request $request): JsonResponse
    {
        $request->validate(['pack' => 'required|string']);

        $workspace = $request->attributes->get('_workspace');
        $url       = $this->stripe->createCreditPackCheckout(
            $workspace,
            $request->pack,
            $request->user()->email,
        );

        return response()->json(['checkout_url' => $url]);
    }

    /**
     * POST /api/billing/cancel
     * Cancel subscription at end of billing period.
     */
    public function cancel(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('_workspace');
        $this->stripe->cancelSubscription($workspace);

        return response()->json(['message' => 'Subscription will cancel at end of current billing period.']);
    }

    /**
     * POST /api/webhooks/stripe
     * Handle Stripe webhook events.
     * Must be outside auth middleware — verified via Stripe signature.
     */
    public function webhook(Request $request): Response
    {
        $payload   = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        try {
            $event = $this->stripe->parseWebhook($payload, $signature);
            $this->stripe->handleWebhookEvent($event);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning("[Stripe] Webhook signature verification failed: {$e->getMessage()}");
            return response('Unauthorized', 401);
        } catch (\Throwable $e) {
            Log::error("[Stripe] Webhook handler error: {$e->getMessage()}");
            return response('Internal Server Error', 500);
        }

        return response('OK', 200);
    }
}
